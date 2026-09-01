from __future__ import annotations

import unittest

from scripts.others_control.defense_etoile.geometry import coordinate_distance, movement_hop


class GeometryTests(unittest.TestCase):
    def test_long_recall_hops_are_canonical_and_converge(self) -> None:
        current = (101, -35, 20)
        destination = (1, 1, 0)
        previous_distance = coordinate_distance(current, destination)

        for _ in range(20):
            next_coordinates = movement_hop(current, destination)
            self.assertEqual(0, sum(next_coordinates) % 2)
            self.assertLessEqual(coordinate_distance(current, next_coordinates), 10)
            distance = coordinate_distance(next_coordinates, destination)
            self.assertLess(distance, previous_distance)
            current = next_coordinates
            previous_distance = distance
            if current == destination:
                break

        self.assertEqual(destination, current)

    def test_intermediate_recall_hop_does_not_stop_in_the_defense_ring(self) -> None:
        destination = (0, 0, 0)
        intermediate = movement_hop((11, 11, 0), destination)

        self.assertGreater(coordinate_distance(intermediate, destination), 1)

    def test_recall_hop_invariants_on_a_coordinate_sample(self) -> None:
        origin = (0, 0, 0)
        for x in range(-25, 26):
            for y in range(-25, 26):
                for z in range(-25, 26):
                    destination = (x, y, z)
                    original_distance = coordinate_distance(origin, destination)
                    if sum(destination) % 2 != 0 or original_distance <= 10:
                        continue
                    intermediate = movement_hop(origin, destination)
                    self.assertEqual(0, sum(intermediate) % 2)
                    self.assertLessEqual(coordinate_distance(origin, intermediate), 10)
                    self.assertLess(
                        coordinate_distance(intermediate, destination),
                        original_distance,
                    )
                    self.assertGreater(coordinate_distance(intermediate, destination), 1)


if __name__ == "__main__":
    unittest.main()
